import { Container } from '@cloudflare/containers';

/*
 * Media worker for Zoom recordings.
 *
 * Laravel POSTs one signed step to /jobs. The Worker answers 202 and hands the
 * step to one Durable Object per recording (per instance size). That object
 * starts the container, keeps the PHP request open until the command exits,
 * posts the result back to Laravel, and stops the container so it stops
 * billing. Zoom never calls this Worker.
 */

export interface Env {
    MEDIA_SMALL: DurableObjectNamespace<MediaSmall>;
    MEDIA_LARGE: DurableObjectNamespace<MediaLarge>;
    MEDIA_WORKER_SECRET: string;
    CALLBACK_URL: string;
    R2_ENDPOINT: string;
    R2_FILES_BUCKET: string;
    R2_PUBLIC_BUCKET: string;
    R2_ACCESS_KEY_ID: string;
    R2_SECRET_ACCESS_KEY: string;
}

type Step = 'download' | 'rendition' | 'poster';

interface Job {
    recording_id: number;
    step: Step;
    rendition?: '480p' | '720p' | '1080p' | null;
    directory: string;
    download_url?: string | null;
    download_token?: string | null;
    sent_at: number;
}

interface Running {
    key: string;
    job: Job;
    started_at: number;
    attempts: number;
}

const SIGNATURE_HEADER = 'X-Media-Signature';
const MAX_SKEW_SECONDS = 300;
const MAX_ATTEMPTS = 3;

export default {
    async fetch(request: Request, env: Env): Promise<Response> {
        const url = new URL(request.url);
        if (request.method !== 'POST' || url.pathname !== '/jobs') {
            return new Response('Not found', { status: 404 });
        }

        const body = await request.text();
        if (
            !(await verify(
                body,
                request.headers.get(SIGNATURE_HEADER),
                env.MEDIA_WORKER_SECRET,
            ))
        ) {
            return new Response('Bad signature', { status: 401 });
        }

        let job: Job;
        try {
            job = JSON.parse(body) as Job;
        } catch {
            return new Response('Bad JSON', { status: 400 });
        }

        if (
            Math.abs(Date.now() / 1000 - Number(job.sent_at)) > MAX_SKEW_SECONDS
        ) {
            return new Response('Stale request', { status: 401 });
        }
        if (
            !Number.isInteger(job.recording_id) ||
            !job.directory ||
            !['download', 'rendition', 'poster'].includes(job.step)
        ) {
            return new Response('Bad job', { status: 422 });
        }
        if (
            job.step === 'rendition' &&
            !['480p', '720p', '1080p'].includes(job.rendition ?? '')
        ) {
            return new Response('Bad rendition', { status: 422 });
        }

        const namespace = needsLargeInstance(job)
            ? env.MEDIA_LARGE
            : env.MEDIA_SMALL;
        const stub = namespace.get(
            namespace.idFromName(String(job.recording_id)),
        );

        return stub.accept(job);
    },
};

/** 720p and 1080p need the 4 GiB / 8 GB instance; everything else fits on basic. */
function needsLargeInstance(job: Job): boolean {
    return (
        job.step === 'rendition' &&
        (job.rendition === '720p' || job.rendition === '1080p')
    );
}

function stepKey(job: Job): string {
    return job.step === 'rendition' ? `rendition:${job.rendition}` : job.step;
}

class MediaContainer extends Container<Env> {
    defaultPort = 8080;
    // FFmpeg inside the container does not count as activity, so the idle window
    // must outlast the longest encode. The container is stopped explicitly as
    // soon as the step finishes, so this only matters if something goes wrong.
    sleepAfter = '4h';

    constructor(ctx: DurableObjectState<{}>, env: Env) {
        super(ctx, env);
        this.envVars = {
            R2_ENDPOINT: env.R2_ENDPOINT,
            R2_FILES_BUCKET: env.R2_FILES_BUCKET,
            R2_PUBLIC_BUCKET: env.R2_PUBLIC_BUCKET,
            R2_ACCESS_KEY_ID: env.R2_ACCESS_KEY_ID,
            R2_SECRET_ACCESS_KEY: env.R2_SECRET_ACCESS_KEY,
        };
    }

    /** True while this instance has a run in memory. A reset loses the run and this flag together. */
    private active = false;

    /** Called by the Worker. Returns at once; the step keeps running here. */
    async accept(job: Job): Promise<Response> {
        const key = stepKey(job);
        let running = await this.ctx.storage.get<Running>('running');

        // A reset (deploy, eviction, library abort) loses the in-memory run but
        // not the stored flag. A flag with no run behind it is not a duplicate.
        if (running && !this.active) {
            await this.ctx.storage.delete('running');
            running = undefined;
        }

        if (running) {
            if (running.key === key) {
                return Response.json(
                    { accepted: true, duplicate: true, step: key },
                    { status: 202 },
                );
            }

            return Response.json(
                { accepted: false, running: running.key },
                { status: 409 },
            );
        }

        await this.ctx.storage.put<Running>('running', {
            key,
            job,
            started_at: Date.now(),
            attempts: 1,
        });
        await this.armWatchdog();
        this.launch(job);

        return Response.json({ accepted: true, step: key }, { status: 202 });
    }

    /**
     * Runs from the alarm every minute while a step is stored as running. If the
     * run behind it was lost, start it again (twice at most), then give up and
     * report a failure so Laravel shows the retry button instead of hanging.
     */
    async watchdog(): Promise<void> {
        const running = await this.ctx.storage.get<Running>('running');
        if (!running) {
            return;
        }

        if (!this.active) {
            if (running.attempts >= MAX_ATTEMPTS) {
                await this.ctx.storage.delete('running');
                const [step, rendition] = running.key.split(':') as [
                    Step,
                    Job['rendition'],
                ];
                await this.report(
                    running.job.recording_id,
                    step,
                    rendition ?? null,
                    {
                        ok: false,
                        error: `The media worker lost this step ${MAX_ATTEMPTS} times.`,
                    },
                );

                return;
            }

            console.log(
                `Restarting lost step ${running.key} for recording ${running.job.recording_id}`,
            );
            await this.ctx.storage.put<Running>('running', {
                ...running,
                started_at: Date.now(),
                attempts: running.attempts + 1,
            });
            this.launch(running.job);
        }

        await this.armWatchdog();
    }

    private async armWatchdog(): Promise<void> {
        this.deleteSchedules('watchdog');
        await this.schedule(60, 'watchdog');
    }

    private launch(job: Job): void {
        this.active = true;
        this.ctx.waitUntil(
            this.run(job).finally(() => {
                this.active = false;
            }),
        );
    }

    private async run(job: Job): Promise<void> {
        let result: Record<string, unknown>;

        try {
            // A container from an earlier step may still be shutting down.
            for (let i = 0; i < 60 && this.ctx.container?.running; i++) {
                await new Promise((resolve) => setTimeout(resolve, 500));
            }
            if (this.ctx.container?.running) {
                await this.destroy();
            }

            const response = await this.containerFetch(
                new Request('http://container/run', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(job),
                }),
                this.defaultPort,
            );
            const text = await response.text();
            const last = text.trim().split('\n').pop() ?? '';

            try {
                result = JSON.parse(last);
            } catch {
                result = {
                    ok: false,
                    error: `Container answered ${response.status}: ${last.slice(0, 500)}`,
                };
            }
        } catch (error) {
            result = {
                ok: false,
                error: `Container request failed: ${String(error)}`,
            };
        }

        // Shut the container down before reporting: Laravel answers the report by
        // sending the next step, which may land on this same object and must get
        // a fresh container. The work is done, so kill it and stop billing now
        // rather than after sleepAfter.
        await this.ctx.storage.delete('running');
        this.deleteSchedules('watchdog');
        try {
            await this.destroy();
        } catch (error) {
            console.error('Could not stop container', error);
        }

        await this.report(
            job.recording_id,
            job.step,
            job.rendition ?? null,
            result,
        );
    }

    override onError(error: unknown): void {
        console.error('Container error', error);
    }

    private async report(
        recordingId: number,
        step: Step,
        rendition: Job['rendition'] | null,
        result: Record<string, unknown>,
    ) {
        console.log(
            `Recording ${recordingId} ${step}${rendition ? ` ${rendition}` : ''}: ${JSON.stringify(result).slice(0, 1000)}`,
        );
        const body = JSON.stringify({
            ...result,
            recording_id: recordingId,
            step,
            rendition,
            sent_at: Math.floor(Date.now() / 1000),
        });
        const signature = await sign(body, this.env.MEDIA_WORKER_SECRET);

        for (let attempt = 0; attempt < 5; attempt++) {
            try {
                const response = await fetch(this.env.CALLBACK_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        [SIGNATURE_HEADER]: signature,
                    },
                    body,
                });
                if (response.ok) {
                    return;
                }
                console.error(
                    `Callback answered ${response.status}: ${(await response.text()).slice(0, 300)}`,
                );
                if (response.status < 500) {
                    return;
                }
            } catch (error) {
                console.error('Callback failed', error);
            }
            await new Promise((resolve) =>
                setTimeout(resolve, 2000 * 2 ** attempt),
            );
        }
    }
}

export class MediaSmall extends MediaContainer {}
export class MediaLarge extends MediaContainer {}

async function hmac(secret: string): Promise<CryptoKey> {
    return crypto.subtle.importKey(
        'raw',
        new TextEncoder().encode(secret),
        { name: 'HMAC', hash: 'SHA-256' },
        false,
        ['sign'],
    );
}

async function sign(body: string, secret: string): Promise<string> {
    const digest = await crypto.subtle.sign(
        'HMAC',
        await hmac(secret),
        new TextEncoder().encode(body),
    );

    return [...new Uint8Array(digest)]
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');
}

async function verify(
    body: string,
    header: string | null,
    secret: string,
): Promise<boolean> {
    if (!header || !secret) {
        return false;
    }
    const expected = new TextEncoder().encode(await sign(body, secret));
    const given = new TextEncoder().encode(header.trim().toLowerCase());
    if (expected.length !== given.length) {
        return false;
    }

    return crypto.subtle.timingSafeEqual(expected, given);
}
