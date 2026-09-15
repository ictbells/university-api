<x-mail::message>
# Your matric number

Hello {{ $studentName }},

Your student record is now active. Use your matric number to sign in to the student portal. {{ $loginNote }}

**Matric number:** {{ $matricNumber }}

@if ($applicationNumber)
**Application number:** {{ $applicationNumber }}
@endif

**Password:** Use the password you created when you registered. If you have forgotten it, use the forgot-password link on the sign-in page.

<x-mail::button :url="$portalUrl">
Open student portal
</x-mail::button>

Keep these details safe. Your email address is used for notifications and password reset only — sign in with your matric number, not your email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
