{{--
    Der Hinweis über allem: was das hier ist, und wann es weg ist.

    Er steht in der Kopfleiste und nicht auf der Startseite, weil man ihn auf
    jeder Seite sehen soll -- wer in der dritten Maske eine Wartungsakte
    einträgt, hat die Startseite längst vergessen.
--}}
@php
    $demo = app(\App\Core\Demo\DemoMode::class);
    $naechster = $demo->nextReset();
    $stunden = (int) max(0, now()->diffInHours($naechster));
@endphp

<div class="fi-demo-banner">
    <span class="fi-demo-dot"></span>
    <span>
        <strong>{{ __('demo.banner.title') }}</strong>
        {{ __('demo.banner.body') }}
    </span>
    <span class="fi-demo-reset" title="{{ $naechster->format('d.m.Y H:i') }}">
        {{ trans_choice('demo.banner.next_reset', $stunden, ['hours' => $stunden]) }}
    </span>
</div>

<style>
    .fi-demo-banner {
        display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;
        padding: .35rem .75rem; font-size: .8rem; line-height: 1.3;
        background: rgb(254 249 195); color: rgb(113 63 18);
        border-bottom: 1px solid rgb(234 179 8 / .4);
    }
    .dark .fi-demo-banner { background: rgb(66 32 6); color: rgb(253 224 71); }
    .fi-demo-dot { width: .5rem; height: .5rem; border-radius: 999px; background: currentColor; }
    .fi-demo-reset { margin-left: auto; font-variant-numeric: tabular-nums; opacity: .85; }
</style>
