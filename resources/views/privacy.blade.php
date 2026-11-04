{{--
    The privacy policy. Every statement here describes what the code does —
    change the code and this page together (and bump catalog.privacy.updated).
--}}
<x-layouts.app title="Privacy policy — collectAI">
    <article class="space-y-6 text-neutral-700 dark:text-neutral-300">
        <header>
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">Privacy policy</h1>
            <p class="mt-1 text-sm text-neutral-500">Last updated {{ \Illuminate\Support\Carbon::parse(config('catalog.privacy.updated'))->format('j F Y') }}</p>
        </header>

        <p>
            collectAI is a catalog of diecast cars, with optional accounts for keeping your own collection and a
            scanner that recognises cars in your photos. This page explains what we collect, why, and what you can do
            about it. It applies to collectai.club and the collectAI iPhone app, which shows the same site.
        </p>

        <section>
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">What we collect</h2>
            <ul class="mt-2 list-disc space-y-2 pl-5">
                <li><strong>Browsing the catalog</strong> needs no account. Like any website, our servers and our network provider (Cloudflare) see your IP address and browser details to deliver pages and protect the site from abuse; these appear in short-lived server logs.</li>
                <li><strong>Your account:</strong> your name, email address and password. The password is stored only as a one-way hash — nobody, including us, can read it.</li>
                <li><strong>Your collection and wishlist:</strong> the cars you add, with the quantity, condition and date acquired you choose to enter.</li>
                <li><strong>Scans:</strong> when you use Scan, we receive your photos, find the cars in them and keep a cropped picture of each car, with what the scanner read and what you confirmed. We do not keep the full photo.</li>
                <li><strong>Sign-in session:</strong> a cookie that keeps you signed in and protects forms against forgery. Your active sessions are stored with your IP address and browser name so they can be secured. We use no advertising, analytics or tracking cookies.</li>
            </ul>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">How we use it</h2>
            <ul class="mt-2 list-disc space-y-2 pl-5">
                <li>To run your account, show your collection and wishlist, and work out its value from published price data.</li>
                <li>To recognise the cars in your scans. This runs on collectAI's own servers; your photos are not sent to any outside AI or image service.</li>
                <li><strong>Only if you tick “Help teach the scanner”</strong>, the cropped pictures of cars you confirm or name are kept, with what they are, to train collectAI's own recognizer. They are never published or shared outside collectAI. Without that tick, they are deleted when you finish the scan, or after 24 hours at the latest.</li>
                <li>To keep the site secure: limiting repeated sign-in and sign-up attempts by IP address, and blocking abuse.</li>
            </ul>
            <p class="mt-2">We do not sell your data, use it for advertising, or share it with data brokers.</p>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Who else handles it</h2>
            <ul class="mt-2 list-disc space-y-2 pl-5">
                <li>Our hosting providers run the servers and the database your data is stored on.</li>
                <li>Cloudflare delivers the site and catalog photos and protects it from attacks; your requests pass through its network.</li>
                <li>Price look-ups ask eBay's official service about cars in the catalog. No personal information is included.</li>
            </ul>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Camera and photos (iPhone app)</h2>
            <p class="mt-2">
                The app asks for the camera only when you take a photo to scan, and for your photo library only when you
                choose a photo to upload. It reads nothing else on your device and does not track you across other apps or websites.
            </p>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Keeping and deleting your data</h2>
            <ul class="mt-2 list-disc space-y-2 pl-5">
                <li>You can see and change your details on your <a href="{{ route('account.show') }}" class="underline">account page</a>, and delete any scan — with its pictures — from the Scan page.</li>
                <li><strong>Deleting your account</strong> (Account → Delete account) removes your account, collection, wishlist, scans and their pictures straight away.</li>
                <li>Private database backups may hold deleted data for a limited time until they are replaced.</li>
                <li>A recognizer already trained with pictures you agreed to share keeps what it learned, as numbers rather than pictures.</li>
            </ul>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Children</h2>
            <p class="mt-2">collectAI is not directed to children under 13, and we do not knowingly collect their personal information. If you believe a child has created an account, contact us and we will delete it.</p>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Your rights and contact</h2>
            <p class="mt-2">
                You can ask us for a copy of your data, to correct it, or to delete it, by writing to
                <a href="mailto:{{ config('catalog.privacy.contact') }}" class="underline">{{ config('catalog.privacy.contact') }}</a>.
                Depending on where you live you may have further rights under laws such as the GDPR or CCPA; we will honour them.
            </p>
            <p class="mt-2">If this policy changes, the date at the top changes with it, and significant changes are announced on the site.</p>
        </section>
    </article>
</x-layouts.app>
