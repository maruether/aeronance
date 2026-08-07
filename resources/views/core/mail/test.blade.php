<x-mail::message>
# {{ __('mail.test.heading') }}

{{ __('mail.test.intro', ['organisation' => $organisation]) }}

{{ __('mail.test.explains') }}

{{ __('mail.test.sent_at', ['zeit' => $zeit]) }}
</x-mail::message>
