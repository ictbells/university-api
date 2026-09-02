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

Sign in with your {{ strtolower($signInLabel) }} and this new password.

<x-mail::panel>
**You must update your records before you submit this application.** Open **Apply**, pay the application fee if it is unpaid, complete any missing details, and submit.
</x-mail::panel>

Keep these details safe. Email is used for notifications and password reset only — sign in with your {{ strtolower($signInLabel) }}, not your email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
