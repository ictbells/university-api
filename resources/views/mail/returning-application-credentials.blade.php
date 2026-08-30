<x-mail::message>
# Continue your Bells University application

Hello {{ $applicantName }},

Your previous programme is complete and a new application has been started for you. Sign in with the details below to continue.

@if ($matricNumber)
**Matric number:** {{ $matricNumber }}
@endif
@if ($previousApplicationNumber)
**Previous application number:** {{ $previousApplicationNumber }}
@endif
**New application number:** {{ $newApplicationNumber }}

**{{ $signInLabel }}:** {{ $signInValue }}

**Password:** {{ $plainPassword }}

<x-mail::button :url="$portalUrl">
Open student portal
</x-mail::button>

Sign in with your {{ strtolower($signInLabel) }} and this new password. Then open **Apply**, pay the application fee if it is unpaid, and complete the form.

Keep these details safe. Email is used for notifications and password reset only — sign in with your {{ strtolower($signInLabel) }}, not your email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
