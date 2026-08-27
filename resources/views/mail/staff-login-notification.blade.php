<x-mail::message>
# Staff portal sign-in

Hello {{ $staffName }},

Your staff portal account was just signed in.

**When:** {{ $signedInAt }}
**IP address:** {{ $ipAddress }}
**Device:** {{ $device }}

If this was you, no action is needed.

If you did not sign in, reset your password immediately and contact ICT.

<x-mail::button :url="$portalUrl">
Open staff portal
</x-mail::button>

<x-mail::button :url="$resetUrl">
Reset password
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
