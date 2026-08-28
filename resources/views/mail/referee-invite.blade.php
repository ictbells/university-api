<x-mail::message>
# Recommendation request

Hello {{ $refereeName }},

{{ $applicantName }} has named you as a referee on a postgraduate application
({{ $applicationNumber }}@if($programme) — {{ $programme }}@endif).

Please use the button below to upload your recommendation letter. The link expires in 30 days and should not be shared.

<x-mail::button :url="$portalUrl">
Submit recommendation letter
</x-mail::button>

If the button does not work, paste this address into your browser:

{{ $portalUrl }}

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
