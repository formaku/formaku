<!doctype html>
<html lang="pl"@if ($siteNavigation['theme'] !== 'system') data-theme="{{ $siteNavigation['theme'] }}"@endif
@if ($siteNavigation['accent'] !== 'formaku') data-accent="{{ $siteNavigation['accent'] }}"@endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $title ?? 'Formaku' }}</title>
    @isset($description)
        <meta name="description" content="{{ $description }}">
    @endisset
    <link rel="stylesheet" href="/vendor/formaku/main.css">
</head>
<body>
    <header class="navbar flex-wrap justify-between gap-4 border-b border-base-300 p-6">
        <a href="/" class="text-2xl font-semibold tracking-tight">formaku<span class="text-secondary">.</span></a>
        @if ($siteNavigation['header'])
            <nav class="min-w-0 max-w-full" aria-label="Menu główne">
                <ul class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                    @foreach ($siteNavigation['header'] as $link)
                        <li class="min-w-0"><a class="link link-hover wrap-anywhere" href="{{ $link['url'] }}">{{ $link['label'] }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif
    </header>
    <main class="mx-auto max-w-6xl px-6 py-10">@yield('content')</main>
    @if ($siteNavigation['footerText'] !== '' || $siteNavigation['footer'])
        <footer class="footer sm:footer-horizontal mx-auto max-w-6xl border-t border-base-300 px-6 py-6 text-sm">
            @if ($siteNavigation['footerText'] !== '')
                <p class="whitespace-pre-line wrap-anywhere">{{ $siteNavigation['footerText'] }}</p>
            @endif
            @if ($siteNavigation['footer'])
                <nav aria-label="Linki stopki">
                    @foreach ($siteNavigation['footer'] as $link)
                        <a class="link link-hover wrap-anywhere" href="{{ $link['url'] }}">{{ $link['label'] }}</a>
                    @endforeach
                </nav>
            @endif
        </footer>
    @endif
</body>
</html>
