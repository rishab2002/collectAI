{{--
    Error pages stand alone: no session, no database, no auth checks — a 500
    may mean the database is down, and the page explaining that must still
    render. Styled from the compiled stylesheet, never inline: the Content
    Security Policy forbids inline styles, which is why Laravel's built-in error
    pages (all inline CSS) are replaced here.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · collectAI</title>
    <link rel="icon" href="/icons/favicon-32.png" sizes="32x32">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-dvh bg-neutral-50 text-neutral-900 dark:bg-neutral-950 dark:text-neutral-100">
    <main class="mx-auto max-w-md px-4 py-24 text-center">
        <p class="text-sm font-medium text-neutral-500">@yield('code')</p>
        <h1 class="mt-2 text-2xl font-semibold">@yield('title')</h1>
        <p class="mt-3 text-neutral-500">@yield('message')</p>
        <a href="/" class="mt-8 inline-block rounded-lg bg-neutral-900 px-4 py-2 font-medium text-white dark:bg-white dark:text-neutral-900">Back to the catalog</a>
    </main>
</body>
</html>
