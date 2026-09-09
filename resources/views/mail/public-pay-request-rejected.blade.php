<x-mail::message>
# Request update

Hello {{ $studentName }},

Your request{{ $offerName ? ' ('.$offerName.')' : '' }}{{ $matric ? ' — '.$matric : '' }} could not be completed.

**Reference:** {{ $token }}

@if ($reason)
**Reason:** {{ $reason }}
@endif

If you need help, contact the relevant office.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
