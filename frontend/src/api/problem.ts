import type { components } from './schema';

/**
 * A failure the UI can render, built from `application/problem+json` (RFC 9457).
 *
 * **`code` is the contract, the texts are not** (E-16, `openapi/README.md`
 * section 6). `title` and `detail` are English fallbacks the API is allowed to
 * change without notice, so the UI translates `code` and falls back to the text
 * only when it does not know the code. That fallback is what keeps the
 * interface usable when the backend gains an error the frontend has not learned
 * yet — the alternative, an empty dialog, is the failure mode this avoids.
 */

/** The exhaustive set of contract error codes, generated from the schema. */
export type ErrorCode = components['schemas']['ErrorCode'];

/**
 * Codes the *client* raises, for conditions the server never gets to report.
 *
 * A separate namespace on purpose: `ErrorCode` is exhaustive by contract, and
 * inventing a value inside it would make a client-side condition look like
 * something the API can return. `client` is not one of the prefixes U-7 allows,
 * which is exactly why it cannot collide.
 */
export type ClientErrorCode =
  /** The request never reached the server: offline, DNS, TLS, aborted connection. */
  | 'client.network'
  /** The server answered, but not with anything this client can read. */
  | 'client.malformed_response';

export type ProblemCode = ErrorCode | ClientErrorCode;

export interface ProblemFields {
  readonly status: number;
  readonly code: ProblemCode;
  readonly title?: string | undefined;
  readonly detail?: string | undefined;
  readonly instance?: string | undefined;
  readonly requestId?: string | undefined;
  /** Field path (`body.quota`) to the constraint that failed (`required`, `enum`). */
  readonly errors: Readonly<Record<string, string>>;
  /** Seconds, from `Retry-After`. Only ever present on `429`. */
  readonly retryAfterSeconds?: number | undefined;
}

export class ApiProblem extends Error implements ProblemFields {
  readonly status: number;
  readonly code: ProblemCode;
  override readonly cause?: unknown;
  readonly title: string | undefined;
  readonly detail: string | undefined;
  readonly instance: string | undefined;
  readonly requestId: string | undefined;
  readonly errors: Readonly<Record<string, string>>;
  readonly retryAfterSeconds: number | undefined;

  constructor(fields: ProblemFields, cause?: unknown) {
    // The Error message is for logs and stack traces, never for the screen.
    // Anything a user reads comes from the translation of `code`.
    super(`${fields.code} (HTTP ${String(fields.status)})`);

    this.name = 'ApiProblem';
    this.status = fields.status;
    this.code = fields.code;
    this.title = fields.title;
    this.detail = fields.detail;
    this.instance = fields.instance;
    this.requestId = fields.requestId;
    this.errors = fields.errors;
    this.retryAfterSeconds = fields.retryAfterSeconds;

    if (cause !== undefined) {
      this.cause = cause;
    }
  }

  /** `true` when the session is gone and the only way forward is signing in. */
  get isUnauthenticated(): boolean {
    return this.status === 401;
  }

  /** `true` when the operation itself is off limits for this administrator. */
  get isForbidden(): boolean {
    return this.status === 403;
  }

  /**
   * `true` when the failure is worth retrying unchanged. A network hiccup and a
   * `503` are; a `422` never is, because the same body will fail again.
   */
  get isTransient(): boolean {
    return this.code === 'client.network' || this.status === 503 || this.status === 429;
  }
}

export function isApiProblem(error: unknown): error is ApiProblem {
  return error instanceof ApiProblem;
}

function asString(value: unknown): string | undefined {
  return typeof value === 'string' && value !== '' ? value : undefined;
}

function asFieldErrors(value: unknown): Record<string, string> {
  if (typeof value !== 'object' || value === null) {
    return {};
  }

  const result: Record<string, string> = {};

  for (const [field, reason] of Object.entries(value as Record<string, unknown>)) {
    if (typeof reason === 'string') {
      result[field] = reason;
    }
  }

  return result;
}

/**
 * Every code the contract knows. Used to decide whether a `code` coming off the
 * wire is one this build understands; an unknown one is kept as-is and rendered
 * through the text fallback rather than dropped.
 */
function asProblemCode(value: unknown, status: number): ProblemCode {
  if (typeof value === 'string' && value !== '') {
    return value as ProblemCode;
  }

  // A problem document without a `code` violates the contract. Rather than
  // throw a second error on top of the first, fall back to what the status says.
  return status === 401 ? 'request.unauthenticated' : 'request.internal_error';
}

/**
 * Builds an {@link ApiProblem} from a response that is already known to be an
 * error. `body` is whatever came out of the response, parsed or not.
 */
export function problemFromResponse(
  status: number,
  body: unknown,
  headers: Headers,
): ApiProblem {
  const document = (typeof body === 'object' && body !== null ? body : {}) as Record<string, unknown>;

  const retryAfter = headers.get('Retry-After');
  const retryAfterSeconds = retryAfter !== null && /^\d+$/.test(retryAfter.trim())
    ? Number.parseInt(retryAfter, 10)
    : undefined;

  return new ApiProblem({
    status,
    code: asProblemCode(document.code, status),
    title: asString(document.title),
    detail: asString(document.detail),
    instance: asString(document.instance),
    // The header wins over the body: it is set even when the body is not a
    // problem document at all (a proxy's error page, say).
    requestId: asString(headers.get('X-Request-Id')) ?? asString(document.request_id),
    errors: asFieldErrors(document.errors),
    retryAfterSeconds,
  });
}

export function networkProblem(cause: unknown): ApiProblem {
  return new ApiProblem({ status: 0, code: 'client.network', errors: {} }, cause);
}

export function malformedResponseProblem(status: number, headers: Headers, cause: unknown): ApiProblem {
  return new ApiProblem(
    {
      status,
      code: 'client.malformed_response',
      requestId: asString(headers.get('X-Request-Id')),
      errors: {},
    },
    cause,
  );
}
