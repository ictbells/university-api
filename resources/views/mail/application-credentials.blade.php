<x-mail::message>
# Your Bells University student portal account

Hello {{ $applicantName }},

Use the details below to sign in to the student portal.

**Application number:** {{ $applicationNumber }}

**Sign-in ID:** {{ $loginId }}

@if ($plainPassword)
**Password:** {{ $plainPassword }}
@else
**Password:** Use the password you created when you registered. If you have forgotten it, use the forgot-password link on the sign-in page.
@endif

<x-mail::button :url="$portalUrl">
Open student portal
</x-mail::button>

After you sign in, update your records, upload any required documents, and submit your application if it is not yet submitted. If the application fee is unpaid, the portal will ask you to pay before you continue.

Keep these details safe. Your email address is used for notifications and password reset only — sign in with your application number or JAMB number, not your email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
