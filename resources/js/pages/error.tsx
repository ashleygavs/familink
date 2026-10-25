import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Home, LogIn, TriangleAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import { dashboard, login } from '@/routes';

const MESSAGES: Record<number, { title: string; text: string }> = {
    403: {
        title: 'Your account cannot open this page',
        text: 'This page is not part of your role. If you think it should be, please ask your administrator.',
    },
    404: {
        title: 'We could not find that page',
        text: 'It may have been moved, or your account may not have access to it. Please check the address, or go back and try again.',
    },
    419: {
        title: 'This page was open too long',
        text: 'For your safety, pages expire after a while. Please go back and try again. You may need to log in again.',
    },
    429: {
        title: 'That was too many tries',
        text: 'Please wait a minute and try again.',
    },
    500: {
        title: 'Something went wrong on our side',
        text: 'Nothing you did caused this, and your saved records are safe. Please try again. If it keeps happening, tell your administrator.',
    },
    503: {
        title: 'FamiLink is being updated',
        text: 'Please try again in a few minutes.',
    },
};

/**
 * One calm page for every error, with a way out and no technical details. People who are logged in see it inside the
 * app; people who are not see it on the same card as the login page.
 */
export default function ErrorPage({ status }: { status: number }) {
    const { auth } = usePage().props;
    const loggedIn = Boolean(auth?.user);
    const message = MESSAGES[status] ?? MESSAGES[500];

    const content = (
        <div
            role="alert"
            className="mx-auto flex max-w-xl flex-col items-center gap-6 py-10 text-center"
        >
            <Head title={message.title} />
            <div className="flex size-16 items-center justify-center rounded-2xl bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300">
                <TriangleAlert className="size-9" />
            </div>
            <div className="space-y-2">
                <h1 className="text-3xl font-bold">{message.title}</h1>
                <p className="text-lg text-muted-foreground">{message.text}</p>
            </div>
            <div className="flex flex-wrap justify-center gap-3">
                <Button asChild>
                    {loggedIn ? (
                        <Link href={dashboard()}>
                            <Home />
                            Go to my dashboard
                        </Link>
                    ) : (
                        <Link href={login()}>
                            <LogIn />
                            Go to log in
                        </Link>
                    )}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => window.history.back()}
                >
                    <ArrowLeft />
                    Go back
                </Button>
            </div>
        </div>
    );

    return loggedIn ? (
        <AppLayout>{content}</AppLayout>
    ) : (
        <AuthLayout>{content}</AuthLayout>
    );
}
