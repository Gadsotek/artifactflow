<svg
    {{ $attributes->merge(['class' => 'af-brand-mark']) }}
    data-brand-mark
    aria-hidden="true"
    viewBox="82.8 56 346.4 300"
    xmlns="http://www.w3.org/2000/svg"
>
    {{-- Always sits on a dark surface (sidebar, auth story), so this carries the
         dark-background palette: violets lightened one step, boundary face left as
         currentColor and pinned to paper by .af-brand-mark. --}}
    <path fill="#8c84ff" d="M256 56L429.2 356L318.4 312.2L273.5 173.8Z"/>
    <path fill="#675df6" d="M429.2 356L82.8 356L176.1 282L318.4 312.2Z"/>
    <path fill="currentColor" d="M82.8 356L256 56L273.5 173.8L176.1 282Z"/>
</svg>
