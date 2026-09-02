<x-mail::message>
# Your Bells University student portal account

Hello {{ $applicantName }},

Use the details below to sign in to the student portal.

**{{ $signInLabel }}:** {{ $signInValue }}

@if ($plainPassword)
**Password:** {{ $plainPassword }}
@else
**Password:** Use the password you created when you registered. If you have forgotten it, use the forgot-password link on the sign-in page.
@endif

<x-mail::button :url="$portalUrl">
Open student portal
</x-mail::button>

<x-mail::panel>
**You must update your records before you submit your application.** Sign in, complete any missing details (including phone from NIN and programme choice), upload required documents, then submit. If the application fee is unpaid, the portal will ask you to pay before you continue.
</x-mail::panel>

Keep these details safe. Your email address is used for notifications and password reset only — sign in with your {{ $signInLabel }}, not your email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
