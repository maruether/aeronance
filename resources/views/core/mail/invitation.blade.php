<x-mail::message>
# {{ __('mail.invitation.heading', ['organisation' => $organisation]) }}

{{ __('mail.invitation.greeting', ['name' => $name]) }}

{{ __('mail.invitation.intro', ['organisation' => $organisation]) }}

<x-mail::button :url="$url">
{{ __('mail.invitation.button') }}
</x-mail::button>

{{ trans_choice('mail.invitation.expires', $stunden, ['stunden' => $stunden]) }}

{{ __('mail.invitation.ignore') }}
</x-mail::message>
