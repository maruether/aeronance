<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Core\Access\AccessSetup;
use App\Core\Models\Qualification;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use App\Modules\Billing\Models\Customer;
use App\Modules\Billing\Models\LabourRate;
use App\Modules\Billing\Permissions;
use App\Modules\Fleet\Models\Aircraft;
use App\Modules\TaskCards\Actions\CertifyTaskCard;
use App\Modules\TaskCards\Actions\IssueRelease;
use App\Modules\TaskCards\Actions\ManageWorkOrder;
use App\Modules\TaskCards\Enums\ActivityKind;
use App\Modules\TaskCards\Enums\ParticipationKind;
use App\Modules\TaskCards\Models\TaskCard;
use App\Modules\TaskCards\Models\WorkOrder;
use App\Modules\TaskCards\Permissions as CardPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Was jeder Abrechnungstest braucht: die Module, einen Aussteller mit
 * Anschrift und Steuernummer, Kunden, Stundensätze und einen freigegebenen
 * Vorgang. Der Vorgang entsteht wie in ReleasePrintTest -- eine Karte, eine
 * Stunde Arbeit, abgezeichnet, freigegeben.
 */
abstract class BillingTestCase extends TestCase
{
    use RefreshDatabase;

    private ?User $inspectorUser = null;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = app(ModuleManager::class);
        foreach (['warehouse', 'fleet', 'taskcards', 'billing'] as $module) {
            $manager->enable($module);
        }
        $manager->forgetCache();

        app(AccessSetup::class)->run();

        config()->set('aeronance.organisation.name', 'Werft Musterhausen GmbH');
        config()->set('aeronance.organisation.address', "Flugplatzstraße 1\n79100 Freiburg");
        config()->set('aeronance.organisation.tax_number', '06/123/45678');
        config()->set('aeronance.organisation.vat_id', 'DE123456789');
        config()->set('aeronance.organisation.iban', 'DE02120300000000202051');
        config()->set('aeronance.organisation.email', 'werft@example.org');
        config()->set('aeronance.organisation.phone', '0761 1234');
        config()->set('aeronance.billing.tax_rate', '19');
        config()->set('aeronance.billing.payment_terms_days', 14);
        config()->set('aeronance.billing.labour_rounding_minutes', 15);
    }

    protected function customer(array $overrides = []): Customer
    {
        return Customer::create([
            'customer_number' => 'K-'.fake()->unique()->numberBetween(1000, 9999),
            'name' => 'Luftsportverein Beispiel e.V.',
            'address' => "Am Segelflugplatz 2\n79110 Freiburg",
            'email' => 'kasse@example.org',
            ...$overrides,
        ]);
    }

    protected function rates(): void
    {
        foreach (ActivityKind::cases() as $kind) {
            LabourRate::create([
                'activity_kind' => $kind,
                'net_rate_per_hour' => '80.00',
                'valid_from' => now()->subYear()->toDateString(),
            ]);
        }
    }

    /** Ein freigegebener Vorgang mit einer Karte und $minutes Arbeit. */
    protected function releasedVisit(int $minutes = 50, ?Aircraft $aircraft = null): WorkOrder
    {
        $order = $this->openVisit($aircraft);
        $card = $this->cardWithWork($order, $minutes);

        app(CertifyTaskCard::class)->complete($card->fresh(), $this->mechanic(), 'Gemacht');
        app(CertifyTaskCard::class)->certify($card->fresh(), $this->inspector());
        app(IssueRelease::class)->handle($order->fresh(), $this->inspector());

        return $order->fresh(['taskCards']);
    }

    protected function openVisit(?Aircraft $aircraft = null): WorkOrder
    {
        $aircraft ??= $this->aircraft();

        return app(ManageWorkOrder::class)->open($aircraft, 'Jahresnachprüfung', $this->inspector());
    }

    protected function cardWithWork(WorkOrder $order, int $minutes, ActivityKind $kind = ActivityKind::Maintenance): TaskCard
    {
        $card = app(ManageWorkOrder::class)->addCard($order, 'Ölwechsel', kind: $kind);
        app(ManageWorkOrder::class)->recordTime($card, $this->mechanic(), $minutes, ParticipationKind::Executed);

        return $card->fresh();
    }

    protected function aircraft(): Aircraft
    {
        return Aircraft::firstOrCreate(['registration' => 'D-KABC'], ['model' => 'ASK 21']);
    }

    protected function mechanic(): User
    {
        return $this->userWith(CardPermissions::CARDS_WORK);
    }

    protected function inspector(): User
    {
        if ($this->inspectorUser !== null) {
            return $this->inspectorUser->fresh();
        }

        $user = $this->userWith(
            CardPermissions::CARDS_WORK,
            CardPermissions::CARDS_CERTIFY,
            CardPermissions::WORK_ORDERS_MANAGE,
        );

        Qualification::create([
            'user_id' => $user->id,
            'type' => Qualification::TYPE_PART66,
            'reference' => 'DE.66.12345',
            'category' => 'B1',
            'valid_from' => now()->subYear()->toDateString(),
        ]);

        return $this->inspectorUser = $user->fresh();
    }

    /** Darf alles in der Abrechnung. */
    protected function clerk(): User
    {
        return $this->userWith(Permissions::VIEW, Permissions::MANAGE, Permissions::ISSUE, Permissions::PAYMENTS);
    }

    protected function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }
}
