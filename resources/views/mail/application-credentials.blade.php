<x-mail::message>
# Application fee confirmed

Hello {{ $applicantName }},

Your application fee has been received. Use the details below to sign in to the student portal and complete your application form.

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

Keep these details safe. Your email address is used for notifications and password reset only — sign in with your application number or JAMB number, not your email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
