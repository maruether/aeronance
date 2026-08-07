<?php

declare(strict_types=1);

namespace App\Modules\Directives\Console;

use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Directives\Actions\ImportDirectives;
use App\Modules\Directives\Models\Directive;
use App\Modules\Directives\Permissions;
use App\Modules\Directives\Sources\Configured\ConfiguredSource;
use App\Modules\Directives\Sources\Configured\SpecRepository;
use App\Modules\Directives\Sources\DirectiveSource;
use App\Modules\Directives\Sources\SinglePageSource;
use App\Modules\Directives\Sources\SourceRegistry;
use App\Modules\Directives\Sources\UnknownType;
use App\Modules\Fleet\Models\Aircraft;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fetching the manufacturers' lists on a schedule.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Worth having only now that there is more than one manufacturer -- a single
 * source is something a person clicks when they think of it.
 *
 * WHAT IT DELIBERATELY DOES NOT DO: assess anything. A new directive arrives
 * unassessed, which the airworthiness check reports as a red flag and the release
 * gate refuses to sign over. That is the intended behaviour: a machine may notice
 * that a manufacturer published something; only a person may say what it means
 * for an aircraft.
 *
 * IT REPORTS WHAT IT SKIPPED. A source without credentials, a source that threw,
 * a manufacturer that answered nothing -- each is named. A refresh that says
 * "done" while quietly covering three of five manufacturers is the failure this
 * module keeps guarding against.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class RefreshDirectivesCommand extends Command
{
    protected $signature = 'aeronance:refresh-directives
        {--source=* : Only these sources; default is every automatic one}
        {--model=* : Only these aircraft models, where a source needs one}
        {--all-types : Fetch every type a source offers -- many requests}
        {--dry-run : Report what would be fetched without writing}';

    protected $description = 'Holt die LTA/TM-Listen der Hersteller und ergänzt die Übersicht.';

    public function handle(
        ModuleManager $modules,
        SourceRegistry $registry,
        SpecRepository $specs,
        ImportDirectives $import,
    ): int {
        if (! $modules->isEnabled('directives')) {
            $this->components->warn('Das LTA/TM-Modul ist nicht aktiv.');

            return self::SUCCESS;
        }

        // A broken manufacturer file is skipped by the loader; saying so here is
        // the difference between "nothing new" and "nobody could read your file".
        foreach ($specs->problems() as $file => $reason) {
            $this->components->error(sprintf('%s: %s', $file, $reason));
        }

        $chosen = (array) $this->option('source');
        $sources = array_filter(
            $registry->automatic(),
            fn (DirectiveSource $s): bool => $chosen === [] || in_array($s->name(), $chosen, true),
        );

        if ($sources === []) {
            $this->components->warn('Keine abrufbare Quelle. Bekannt: '
                .implode(', ', array_keys($registry->all())));

            return self::SUCCESS;
        }

        $user = $this->systemUser();

        if ($user === null) {
            $this->components->error(
                'Kein Benutzer mit der Berechtigung directives.manage vorhanden -- der '
                .'Abruf schreibt unter einem Konto, damit im Audit-Trail steht, wer es war.'
            );

            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $skipped = [];
        $notOurs = [];

        foreach ($sources as $source) {
            if (method_exists($source, 'isUsable') && ! $source->isUsable()) {
                $skipped[$source->name()] = 'Zugangsdaten fehlen';

                continue;
            }

            foreach ($this->optionsFor($source) as $label => $options) {
                if ($this->option('dry-run')) {
                    $this->components->info(sprintf('%s / %s: würde abgerufen', $source->name(), $label));

                    continue;
                }

                try {
                    $result = $import->fromSource($source->name(), $user, $options);
                } catch (UnknownType) {
                    /*
                     * Not a fault. Every source is asked about every type the
                     * club flies, so Schleicher gets asked about a DG-300 every
                     * week -- and says, correctly, that it does not build one.
                     * Listed at the end as information, never as a warning: a
                     * weekly run that cries wolf about a dozen non-events is a
                     * run nobody reads.
                     */
                    $notOurs[] = sprintf('%s (%s)', $source->name(), $label);

                    continue;
                } catch (Throwable $e) {
                    // Reported, never fatal: one manufacturer being down must not
                    // stop the others.
                    $skipped[$source->name().' / '.$label] = $e->getMessage();

                    continue;
                }

                $created += $result['created'];
                $updated += $result['updated'];

                $this->components->twoColumnDetail(
                    sprintf('%s / %s', $source->name(), $label),
                    sprintf('%d neu, %d aktualisiert, %d unverändert',
                        $result['created'], $result['updated'], $result['unchanged']),
                );

                // A number the manufacturer used twice. Named, because the
                // counts above cannot show it: the second line was read and not
                // stored, and only a person can decide what it should be called.
                foreach ($result['collisions'] ?? [] as $number) {
                    $this->components->warn(sprintf(
                        '%s: "%s" kommt in der Liste mehrfach vor. Nur der erste '
                        .'Eintrag wurde übernommen -- die weiteren bitte von Hand '
                        .'anlegen, mit einer eigenen Nummer.',
                        $source->name(),
                        $number,
                    ));
                }

                /*
                 * What a secondary list handed back to the source that owns it.
                 *
                 * Said out loud rather than left to the counts: "0 neu" reads as
                 * "nothing was published" and as "everything is already there
                 * under its own manufacturer", and only one of those is fine.
                 * Aquila hands 67 Rotax numbers over on an ordinary run.
                 */
                if (($result['deferred'] ?? []) !== []) {
                    $this->components->info(sprintf(
                        '%s: %d Eintrag/Einträge bleiben bei der Quelle, die sie führt (%s%s).',
                        $source->name(),
                        count($result['deferred']),
                        implode(', ', array_slice($result['deferred'], 0, 5)),
                        count($result['deferred']) > 5 ? ', …' : '',
                    ));
                }
            }
        }

        foreach ($skipped as $what => $why) {
            $this->components->warn(sprintf('übersprungen — %s: %s', $what, $why));
        }

        if ($notOurs !== []) {
            $this->components->info('nicht zuständig: '.implode(', ', $notOurs));
        }

        $this->newLine();
        $this->components->info(sprintf(
            '%d neu, %d aktualisiert. %d Anweisungen insgesamt.',
            $created,
            $updated,
            Directive::count(),
        ));

        if ($created > 0) {
            // Said plainly, because it is the point: nothing is decided yet.
            $this->components->warn(
                'Neue Anweisungen sind unbeurteilt und blockieren die Freigabe, bis '
                .'jemand Qualifiziertes sie Zeile für Zeile beurteilt hat.'
            );
        }

        return self::SUCCESS;
    }

    /**
     * What to ask each source for.
     *
     * A single-page source takes no arguments at all. A per-type one needs either
     * the models the fleet actually flies -- which is the sensible default,
     * because a club has two types and a manufacturer has forty -- or every type
     * on request.
     *
     * @return array<string, array<string, mixed>>
     */
    private function optionsFor(DirectiveSource $source): array
    {
        $singlePage = $source instanceof SinglePageSource
            || ($source instanceof ConfiguredSource && $source->spec()->isSinglePage());

        if ($singlePage) {
            /*
             * One list for the whole club. --all-types is passed through all the
             * same, because for the gazette it means something a manufacturer
             * sheet has no equivalent of: read the ARCHIVE rather than the last
             * few issues. See NflSource::fetch().
             */
            return $this->option('all-types')
                ? ['ganzes Archiv' => ['all' => true]]
                : ['alles' => []];
        }

        if ($this->option('all-types')) {
            return ['alle Muster' => ['all' => true]];
        }

        $models = (array) $this->option('model');

        if ($models === []) {
            // The fleet's own types: fetching forty pages for a club that flies
            // two is rude to somebody else's server.
            $models = Aircraft::query()
                ->where('is_active', true)
                ->pluck('model')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        if ($models === []) {
            return [];
        }

        $options = [];

        foreach ($models as $model) {
            $options[$model] = ['model' => $model];
        }

        return $options;
    }

    /**
     * Whose name the import is recorded under.
     *
     * A scheduled job still writes to an audit trail, and "system" is not an
     * answer an auditor accepts. The first account holding the permission is
     * used -- an installation that wants a dedicated one simply creates it.
     */
    private function systemUser(): ?User
    {
        return User::query()
            ->where('is_active', true)
            ->get()
            ->first(fn (User $u): bool => $u->can(Permissions::DIRECTIVES_MANAGE));
    }
}
