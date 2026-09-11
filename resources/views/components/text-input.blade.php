@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'w-full rounded-lg text-[14px] px-3.5 py-2.5 outline-none transition']) }}
    style="background:var(--panel-alt);border:1px solid var(--line);color:var(--ink);"
    onfocus="this.style.borderColor='var(--mint)';this.style.boxShadow='0 0 0 3px rgba(43,227,143,0.15)'"
    onblur="this.style.borderColor='var(--line)';this.style.boxShadow='none'">