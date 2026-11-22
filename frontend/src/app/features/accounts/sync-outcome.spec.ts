import { classifySync } from './sync-outcome';

describe('classifySync', () => {
  it('keeps a real zero', () => {
    const o = classifySync(200, { sync_run: { status: 'completed', records_processed: 0 } });
    expect(o.kind).toBe('completed');
    expect(o.records).toBe(0);
  });
  it('failed run is failed with its error and no count', () => {
    const o = classifySync(200, { sync_run: { status: 'failed', records_processed: 0, error_message: 'Access denied' } });
    expect(o.kind).toBe('failed');
    expect(o.records).toBeNull();
    expect(o.message).toContain('Access denied');
  });
  it('202 queued is queued, not success', () => {
    const o = classifySync(202, { status: 'queued' });
    expect(o.kind).toBe('queued');
    expect(o.records).toBeNull();
  });
  it('running/pending is not finished', () => {
    expect(classifySync(200, { sync_run: { status: 'running' } }).kind).toBe('pending');
  });
  it('missing or odd data never claims success', () => {
    expect(classifySync(200, {}).kind).toBe('unknown');
    expect(classifySync(200, { sync_run: { status: 'completed' } }).kind).toBe('unknown');
    expect(classifySync(200, { sync_run: { status: 'weird' } }).kind).toBe('unknown');
  });
});
