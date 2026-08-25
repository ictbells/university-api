@extends('receipts.layout')

@section('body')
    <table class="facts">
      <tr><th>Received from</th><td>{{ $payer }}</td></tr>
      @if (!empty($payer_id))
        <tr><th>{{ $payer_id_label }}</th><td class="mono">{{ $payer_id }}</td></tr>
      @endif
      @if (!empty($programme))
        <tr><th>Programme</th><td>{{ $programme }}</td></tr>
      @endif
      <tr><th>Payment for</th><td>{{ $category_label }}</td></tr>
      @if (!empty($invoice_number))
        <tr><th>Invoice number</th><td class="mono">{{ $invoice_number }}</td></tr>
      @endif
      <tr><th>Payment method</th><td>{{ $payment_method }}</td></tr>
      <tr><th>Payment reference</th><td class="mono">{{ $reference }}</td></tr>
      <tr><th>Date paid</th><td>{{ $paid_at }}</td></tr>
    </table>

    <div class="amount-band">
      <p class="label">Amount received</p>
      <p class="figure">₦{{ number_format((float) $amount, 2) }}</p>
      <p class="words">{{ $amount_words }}</p>
    </div>

    @if (!empty($items) && count($items))
      <h3>Particulars</h3>
      <table class="lines">
        <thead>
          <tr>
            <th>Description</th>
            <th class="num">Amount</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($items as $item)
            <tr>
              <td>{{ $item['description'] }}</td>
              <td class="num">₦{{ number_format((float) $item['amount'], 2) }}</td>
            </tr>
          @endforeach
        </tbody>
        <tfoot>
          <tr>
            <td>Total</td>
            <td class="num">₦{{ number_format((float) $amount, 2) }}</td>
          </tr>
        </tfoot>
      </table>
    @endif
@endsection
