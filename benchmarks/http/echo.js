import http from 'k6/http';
import { check } from 'k6';

export const options = {
  vus: 8,
  duration: '30s',
};

export default function () {
  const res = http.get('http://127.0.0.1:9000/');
  check(res, { 'body is hello': (r) => r.body === 'hello' });
}
