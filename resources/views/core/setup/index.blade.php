<!DOCTYPE html>
<html lang="de" class="h-full bg-gray-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('setup.title') }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
               background: #f6f7f9; color: #1f2933; line-height: 1.55; }
        .wrap { max-width: 46rem; margin: 0 auto; padding: 3rem 1.25rem 5rem; }
        h1 { font-size: 1.6rem; margin: 0 0 .35rem; }
        .lead { color: #52606d; margin: 0 0 2rem; }
        .step { background: #fff; border: 1px solid #e4e7eb; border-radius: .6rem;
                padding: 1.25rem 1.4rem; margin-bottom: 1rem; }
        .step h2 { font-size: 1.05rem; margin: 0 0 .6rem; display: flex; align-items: center; gap: .6rem; }
        .badge { font-size: .72rem; font-weight: 600; padding: .16rem .5rem; border-radius: 999px; }
        .badge.done { background: #def7ec; color: #03543f; }
        .badge.todo { background: #eef1f4; color: #52606d; }
        .badge.fail { background: #fde8e8; color: #9b1c1c; }
        .hint { color: #616e7c; font-size: .875rem; margin: .5rem 0 0; }
        .err  { background: #fde8e8; color: #9b1c1c; padding: .7rem .9rem;
                border-radius: .4rem; margin: .75rem 0 0; font-size: .9rem; }
        .ok   { background: #def7ec; color: #03543f; padding: .7rem .9rem;
                border-radius: .4rem; margin: 0 0 1rem; font-size: .9rem; }
        label { display: block; font-size: .85rem; font-weight: 600; margin: .85rem 0 .25rem; }
        input[type=text], input[type=email], input[type=password], input[type=number] {
            width: 100%; padding: .5rem .65rem; border: 1px solid #cbd2d9;
            border-radius: .35rem; font-size: .95rem; font-family: inherit; }
        button { margin-top: 1.1rem; background: #0b7285; color: #fff; border: 0;
                 padding: .6rem 1.1rem; border-radius: .35rem; font-size: .95rem;
                 font-weight: 600; cursor: pointer; font-family: inherit; }
        button:disabled { background: #9aa5b1; cursor: not-allowed; }
        .mod { display: flex; gap: .6rem; padding: .55rem 0; border-top: 1px solid #f0f2f4; }
        .mod:first-of-type { border-top: 0; }
        .mod strong { font-weight: 600; }
        .mod small { color: #616e7c; display: block; }
        .demo { border-color: #b7c4d0; background: #fbfcfd; }
        .demo-list { margin: .6rem 0 0; padding-left: 1.1rem; color: #3e4c59; font-size: .9rem; }
        .demo-list li { margin: .2rem 0; }
        .accounts { border-collapse: collapse; margin-top: .9rem; font-size: .85rem; width: 100%; }
        .accounts th { text-align: left; font-weight: 600; color: #52606d; padding: .25rem .5rem .25rem 0; }
        .accounts td { padding: .2rem .5rem .2rem 0; vertical-align: top; }
        .accounts code { background: #eef1f4; padding: .05rem .3rem; border-radius: .2rem; }
        .check { display: flex; gap: .5rem; align-items: flex-start; font-weight: 400;
                 font-size: .9rem; margin-top: 1rem; }
        .check input { margin-top: .25rem; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>{{ __('setup.title') }}</h1>
    <p class="lead">{{ __('setup.intro') }}</p>

    @if (session('status'))
        <div class="ok">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="err">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    {{-- 1. Database --}}
    <div class="step">
        <h2>
            {{ __('setup.step.database') }}
            <span class="badge {{ $database['ok'] ? 'done' : 'fail' }}">
                {{ $database['ok'] ? '✓' : '!' }}
            </span>
        </h2>
        <p>{{ $database['message'] }}</p>
        @if ($preconfigured)
            <p class="hint">{{ __('setup.db.preconfigured') }}</p>
        @elseif ($database['ok'])
            <p class="hint">{{ __('setup.db.stored_in_env') }}</p>
        @else
            <p class="hint">{{ __('setup.db.hint') }}</p>
            <form method="post" action="{{ route('setup.database') }}">
                @csrf
                <label for="db_host">{{ __('setup.db.field.host') }}</label>
                <input id="db_host" name="db_host" type="text" value="{{ old('db_host', '127.0.0.1') }}" required>

                <label for="db_port">{{ __('setup.db.field.port') }}</label>
                <input id="db_port" name="db_port" type="number" value="{{ old('db_port', 3306) }}" min="1" max="65535" required>

                <label for="db_database">{{ __('setup.db.field.database') }}</label>
                <input id="db_database" name="db_database" type="text" value="{{ old('db_database', 'aeronance') }}" required>

                <label for="db_username">{{ __('setup.db.field.username') }}</label>
                <input id="db_username" name="db_username" type="text" value="{{ old('db_username') }}" required>

                <label for="db_password">{{ __('setup.db.field.password') }}</label>
                <input id="db_password" name="db_password" type="password" autocomplete="new-password">

                <button type="submit">{{ __('setup.db.action') }}</button>
            </form>
        @endif
    </div>

    {{--
        Die Abzweigung: Verein oder Spielwiese.

        Sie steht nach der Datenbank und vor allem anderen, weil sie alles
        andere ersetzt: Wer hier abbiegt, bekommt keinen Administrator-, keinen
        Vereins- und keinen Modulschritt -- in einer Demo steht das alles fest.
        Und sie steht nur da, solange die Datenbank leer ist: Der Demoweg legt
        Beispieldaten an und stellt sie unter taegliches Loeschen.
    --}}
    @if (! $hasAdministrator)
        <div class="step demo">
            <h2>
                {{ __('setup.step.demo') }}
                @if ($demoPreselected)
                    <span class="badge todo">{{ __('setup.demo.preselected') }}</span>
                @endif
            </h2>
            <p class="hint">{{ __('setup.demo.what') }}</p>

            <ul class="demo-list">
                <li>{{ __('setup.demo.point.reset') }}</li>
                <li>{{ __('setup.demo.point.accounts') }}</li>
                <li>{{ __('setup.demo.point.uploads') }}</li>
                <li>{{ __('setup.demo.point.mail') }}</li>
                <li>{{ __('setup.demo.point.fetch') }}</li>
            </ul>

            <table class="accounts">
                <tr>
                    <th>{{ __('setup.demo.account') }}</th>
                    <th>{{ __('setup.demo.password') }}</th>
                    <th>{{ __('setup.demo.can') }}</th>
                </tr>
                @foreach ($demoAccounts as $konto => $angaben)
                    <tr>
                        <td><code>{{ \App\Core\Demo\DemoMode::email($konto) }}</code></td>
                        <td><code>{{ \App\Core\Demo\DemoMode::PASSWORD }}</code></td>
                        <td>{{ $angaben['description'] }}</td>
                    </tr>
                @endforeach
            </table>

            <form method="post" action="{{ route('setup.demo') }}">
                @csrf
                <label class="check">
                    <input type="checkbox" name="confirm" value="1" @checked($demoPreselected)>
                    {{ __('setup.demo.confirm') }}
                </label>
                <button type="submit" @disabled(! $database['ok'])>{{ __('setup.demo.action') }}</button>
            </form>
        </div>
    @endif

    {{-- 2. Migrations --}}
    <div class="step">
        <h2>
            {{ __('setup.step.migrate') }}
            <span class="badge {{ $migrated ? 'done' : 'todo' }}">{{ $migrated ? '✓' : '–' }}</span>
        </h2>
        @if ($migrated)
            <p>{{ __('setup.migrate.already') }}</p>
        @else
            <p class="hint">{{ __('setup.migrate.hint') }}</p>
            <form method="post" action="{{ route('setup.migrate') }}">
                @csrf
                <button type="submit" @disabled(! $database['ok'])>{{ __('setup.migrate.action') }}</button>
            </form>
        @endif
    </div>

    {{-- 3. Administrator --}}
    <div class="step">
        <h2>
            {{ __('setup.step.administrator') }}
            <span class="badge {{ $hasAdministrator ? 'done' : 'todo' }}">{{ $hasAdministrator ? '✓' : '–' }}</span>
        </h2>
        @if ($hasAdministrator)
            <p>{{ __('setup.admin.exists') }}</p>
        @elseif ($migrated)
            <form method="post" action="{{ route('setup.administrator') }}">
                @csrf
                <label for="name">{{ __('setup.admin.name') }}</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required>

                <label for="email">{{ __('setup.admin.email') }}</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required>

                <label for="password">{{ __('setup.admin.password') }}</label>
                <input id="password" name="password" type="password" required>

                <label for="password_confirmation">{{ __('setup.admin.password_confirmation') }}</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required>

                <p class="hint">{{ __('setup.admin.hint') }}</p>
                <button type="submit">{{ __('setup.admin.action') }}</button>
            </form>
        @else
            <p class="hint">{{ __('setup.migrate.hint') }}</p>
        @endif
    </div>

    {{-- 4. Organisation --}}
    {{--
        Steht VOR der Modulauswahl: Der Name erscheint danach in der Kopfzeile
        und auf jedem Ausdruck, die Zeitzone entscheidet, welches Datum ein
        Sperrzettel traegt. Beides spaeter zu bemerken heisst, Papier mit
        falschem Datum in der Welt zu haben.
    --}}
    <div class="step">
        <h2>{{ __('setup.organisation.title') }}</h2>
        <p class="hint">{{ __('setup.organisation.intro') }}</p>

        <form method="post" action="{{ route('setup.organisation') }}">
            @csrf

            <label for="organisation_name">{{ __('setup.organisation.name') }}</label>
            <input id="organisation_name" name="organisation_name" type="text"
                   value="{{ old('organisation_name', config('aeronance.organisation.name')) }}" required>

            <label for="organisation_timezone">{{ __('setup.organisation.timezone') }}</label>
            <input id="organisation_timezone" name="organisation_timezone" type="text"
                   value="{{ old('organisation_timezone', config('aeronance.organisation.timezone')) }}" required>

            <button type="submit">{{ __('setup.organisation.save') }}</button>
        </form>
    </div>

    {{-- 5. Modules --}}
    <div class="step">
        <h2>{{ __('setup.step.modules') }}</h2>
        @if (count($modules) === 0)
            <p>{{ __('setup.modules.none') }}</p>
        @else
            <form method="post" action="{{ route('setup.modules') }}">
                @csrf
                @foreach ($modules as $module)
                    <div class="mod">
                        <input type="checkbox" id="m-{{ $module['name'] }}"
                               name="modules[]" value="{{ $module['name'] }}">
                        <label for="m-{{ $module['name'] }}" style="margin:0;font-weight:400">
                            <strong>{{ $module['title'] }}</strong>
                            <small>{{ $module['description'] }}</small>
                            @if (count($module['requires']) > 0)
                                <small>{{ __('modules.needs', ['modules' => implode(', ', $module['requires'])]) }}</small>
                            @endif
                        </label>
                    </div>
                @endforeach
                <p class="hint">{{ __('setup.modules.hint') }}</p>
                <button type="submit">{{ __('setup.modules.action') }}</button>
            </form>
        @endif
    </div>

    {{-- 5. Finish --}}
    <div class="step">
        <h2>{{ __('setup.step.finish') }}</h2>
        @if ($hasAdministrator)
            <p class="hint">{{ __('setup.finish.hint') }}</p>
            <form method="post" action="{{ route('setup.finish') }}">
                @csrf
                <button type="submit">{{ __('setup.finish.action') }}</button>
            </form>
        @else
            <p class="hint">{{ __('setup.finish.blocked') }}</p>
        @endif
    </div>
</div>
</body>
</html>
