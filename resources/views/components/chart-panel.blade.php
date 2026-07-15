@props(['chartKey', 'title', 'description', 'height' => null])

<div class="panel" {{ $attributes }}>
  <div class="panel-head">
    <div>
      <h3>{{ $title }}</h3>
      <p class="desc">{{ $description }}</p>
    </div>
    <button type="button" class="gear-btn" data-chart-gear="{{ $chartKey }}" title="Configure columns">⚙</button>
  </div>

  {{ $before ?? '' }}

  <div id="chart-{{ $chartKey }}" class="chart-holder" @if($height) style="min-height:{{ $height }}px" @endif></div>

  {{ $slot ?? '' }}
</div>
