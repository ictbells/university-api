<x-mail::message>
# Official transcript ready

Hello {{ $studentName }},

Your official transcript request ({{ $matric }}) is ready.

**Reference:** {{ $token }}

@if ($deliveryMode === 'collect')
{{ $collectInstructions }}
@elseif ($downloadUrl)
You can download your official transcript from the link below.

<x-mail::button :url="$downloadUrl">
View / download transcript
</x-mail::button>
@else
Please follow the instructions from the Registry office.
@endif

<x-mail::button :url="$portalUrl">
Open request page
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
