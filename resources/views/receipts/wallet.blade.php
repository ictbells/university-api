@extends('receipts.layout')

@section('body')
    <table class="facts">
      <tr><th>Received from</th><td>{{ $payer }}</td></tr>
      @if (!empty($payer_id))
        <tr><th>{{ $payer_id_label }}</th><td class="mono">{{ $payer_id }}</td></tr>
      @endif
      <tr><th>Payment for</th><td>{{ $category_label }}</td></tr>
      <tr><th>Payment method</th><td>{{ $payment_method }}</td></tr>
      <tr><th>Payment reference</th><td class="mono">{{ $reference }}</td></tr>
      <tr><th>Date paid</th><td>{{ $paid_at }}</td></tr>
    </table>

    <div class="amount-band">
      <p class="label">Amount credited to wallet</p>
      <p class="figure">₦{{ number_format((float) $amount, 2) }}</p>
      <p class="words">{{ $amount_words }}</p>
    </div>
@endsection
