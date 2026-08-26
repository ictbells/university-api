<x-mail::message>
# Transcript request update

Hello {{ $studentName }},

Your official transcript request ({{ $matric }}) could not be completed.

**Reference:** {{ $token }}

@if ($reason)
**Reason:** {{ $reason }}
@endif

If you need help, contact the Registry.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
