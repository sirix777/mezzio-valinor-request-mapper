// Empirical native zero-HTTP gate, outside the frozen application replay.
import exec from 'k6/execution';
import { Trend } from 'k6/metrics';
const invocation = new Trend('task5_gate_invocation_ms');
export const options = {scenarios: {probe: {executor: 'constant-arrival-rate', rate: 10,
  timeUnit: '1s', duration: '30s', preAllocatedVUs: 8, maxVUs: 8, gracefulStop: '2s'}}};
export default function () {
  const origin = exec.scenario.startTime, index = exec.scenario.iterationInTest;
  if (!Number.isFinite(origin) || !Number.isInteger(index)) exec.test.abort('Native gate execution API unavailable');
  invocation.add(Date.now(), {iteration: String(index), scenario_start_ms: String(origin), evidence_kind: 'real_native_zero_http_interruption'});
}
