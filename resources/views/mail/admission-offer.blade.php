<x-mail::message>
# Offer of admission

Hello {{ $applicantName }},

You have been offered admission{{ $programme ? ' to **'.$programme.'**' : '' }}{{ $session ? ' for the **'.$session.'** academic session' : '' }}.

@if ($applicationNumber)
**Application number:** {{ $applicationNumber }}
@endif
@if ($offerReference)
**Offer reference:** {{ $offerReference }}
@endif

@if ($acceptanceAmount)
Please sign in to the student portal to pay the non-refundable acceptance fee of **₦{{ $acceptanceAmount }}** within two weeks of this offer, then print your receipt. Unpaid offers may be forfeited.
@else
Please sign in to the student portal to pay the acceptance fee within two weeks of this offer and print your receipt. Unpaid offers may be forfeited.
@endif

You can also print your admission letter from the portal after you sign in.

<x-mail::button :url="$portalUrl">
Open student portal
</x-mail::button>

Congratulations,<br>
{{ config('app.name') }}
</x-mail::message>
