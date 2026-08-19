{{--
    Die Zugangsdaten stehen auf der Anmeldeseite.

    Vorgabe: „Dazu sollte auf der loginseite direkt eine liste mit den
    zugangsdaten stehen." Das ist kein Versehen, sondern der Zweck: Eine Demo,
    deren Konten man erst erfragen muss, ist keine.

    Es sind dieselben Angaben, die DemoMode führt -- eine zweite Liste im Blade
    wäre die erste, die veraltet.
--}}
<div class="fi-demo-accounts">
    <p class="fi-demo-accounts-lead">{{ __('demo.accounts.lead') }}</p>

    <table>
        @foreach (\App\Core\Demo\DemoMode::ACCOUNTS as $konto => $angaben)
            <tr>
                <td><code>{{ \App\Core\Demo\DemoMode::email($konto) }}</code></td>
                <td class="fi-demo-accounts-what">{{ $angaben['description'] }}</td>
            </tr>
        @endforeach
    </table>

    <p class="fi-demo-accounts-lead">
        {{ __('demo.accounts.password', ['password' => \App\Core\Demo\DemoMode::PASSWORD]) }}
    </p>
</div>

<style>
    .fi-demo-accounts {
        margin-top: 1.25rem; padding-top: 1rem; font-size: .8rem;
        border-top: 1px solid rgb(0 0 0 / .08);
    }
    .dark .fi-demo-accounts { border-color: rgb(255 255 255 / .1); }
    .fi-demo-accounts table { width: 100%; border-collapse: collapse; }
    .fi-demo-accounts td { padding: .15rem .35rem .15rem 0; vertical-align: top; }
    .fi-demo-accounts code {
        background: rgb(0 0 0 / .05); padding: .05rem .3rem; border-radius: .25rem;
        white-space: nowrap;
    }
    .dark .fi-demo-accounts code { background: rgb(255 255 255 / .08); }
    .fi-demo-accounts-what { opacity: .75; }
    .fi-demo-accounts-lead { margin: 0 0 .5rem; opacity: .85; }
    .fi-demo-accounts-lead:last-child { margin: .6rem 0 0; }
</style>
