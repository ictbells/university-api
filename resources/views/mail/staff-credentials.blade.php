<x-mail::message>
# Your staff portal account

Hello {{ $staffName }},

A staff portal account has been created for you. Use the details below to sign in.

**Email:** {{ $email }}

**Password:** {{ $plainPassword }}

<x-mail::button :url="$portalUrl">
Open staff portal
</x-mail::button>

Change this password after you sign in (Initials → Profile → Change password). Do not share these details.

{{ config('app.name') }}
</x-mail::message>
