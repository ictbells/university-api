<x-mail::message>
# Your request is ready

Hello {{ $studentName }},

Your request{{ $offerName ? ' ('.$offerName.')' : '' }}{{ $matric ? ' — '.$matric : '' }} is ready.

**Reference:** {{ $token }}

@if ($deliveryMode === 'collect')
{{ $collectInstructions }}
@elseif ($downloadUrl)
You can download your document from the link below.

<x-mail::button :url="$downloadUrl">
View / download
</x-mail::button>
@else
Please follow the instructions from the office.
@endif

<x-mail::button :url="$portalUrl">
Open request page
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
