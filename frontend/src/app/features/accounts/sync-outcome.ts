export type SyncKind = 'completed' | 'failed' | 'queued' | 'pending' | 'unknown';

export interface SyncOutcome {
  kind: SyncKind;
  /** Real records_processed from the server. null unless the run completed. */
  records: number | null;
  message: string;
}

/** Turns a sync response into an honest outcome. Success is only claimed for status=completed. */
export function classifySync(httpStatus: number | null, body: any): SyncOutcome {
  const run = body?.sync_run;
  if (run && typeof run.status === 'string') {
    if (run.status === 'completed') {
      const n = Number(run.records_processed);
      if (Number.isFinite(n) && n >= 0) {
        return { kind: 'completed', records: n, message: `Completed. ${n} records processed.` };
      }
      return { kind: 'unknown', records: null, message: 'The server said completed but did not report a record count.' };
    }
    if (run.status === 'failed') {
      return { kind: 'failed', records: null, message: `Failed: ${run.error_message || 'no error message was returned'}` };
    }
    if (run.status === 'pending' || run.status === 'running') {
      return { kind: 'pending', records: null, message: `Not finished yet (status: ${run.status}). No record count is available.` };
    }
    return { kind: 'unknown', records: null, message: `Unrecognised sync status: ${String(run.status)}.` };
  }
  if (httpStatus === 202 || body?.status === 'queued') {
    return { kind: 'queued', records: null, message: 'Queued. The sync has not run yet and no records were processed.' };
  }
  return { kind: 'unknown', records: null, message: 'The server did not return a sync result, so success is not claimed.' };
}

export const SYNC_PILL: Record<SyncKind | 'running', string> = {
  running: 'WAITING FOR RESPONSE...',
  completed: 'SYNC COMPLETED',
  failed: 'SYNC FAILED',
  queued: 'QUEUED, NOT RUN YET',
  pending: 'NOT FINISHED',
  unknown: 'RESULT UNKNOWN',
};
