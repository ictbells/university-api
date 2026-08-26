<x-mail::message>
# Payment received

Hello {{ $studentName }},

We received payment for your official transcript request ({{ $matric }}).

**Reference:** {{ $token }}

Registry will process your request and email you again when it is ready.

<x-mail::button :url="$portalUrl">
Check request status
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
