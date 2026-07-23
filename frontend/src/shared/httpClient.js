import { API_BASE_URL } from "../config/env";
import { clearStoredSession, getCsrfToken } from "./session";

const NETWORK_RETRY_DELAY_MS = 350;
const IDEMPOTENT_METHODS = new Set(["GET", "HEAD"]);

function buildUrl(path, query) {
  const normalizedPath = String(path || "").replace(/^\/+/, "");
  const url = new URL(`${API_BASE_URL}/${normalizedPath}`, window.location.origin);
  Object.entries(query || {}).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== "") {
      url.searchParams.set(key, String(value));
    }
  });
  return url.toString();
}

const wait = (milliseconds) =>
  new Promise((resolve) => window.setTimeout(resolve, milliseconds));

const isAbortError = (error, signal) =>
  signal?.aborted || error?.name === "AbortError";

async function fetchWithNetworkRetry(url, init, method, retryAttempt = 0) {
  try {
    return await fetch(url, init);
  } catch (error) {
    if (isAbortError(error, init.signal)) {
      throw error;
    }

    const canRetry =
      IDEMPOTENT_METHODS.has(method) &&
      retryAttempt < 1 &&
      !init.signal?.aborted;

    if (canRetry) {
      await wait(NETWORK_RETRY_DELAY_MS);
      if (init.signal?.aborted) {
        throw new DOMException("La solicitud fue cancelada.", "AbortError");
      }
      return fetchWithNetworkRetry(url, init, method, retryAttempt + 1);
    }

    const networkError = new Error(
      "No se pudo conectar con el servidor. Verificá que el backend esté iniciado y volvé a intentar.",
    );
    networkError.code = "NETWORK_ERROR";
    networkError.cause = error;
    throw networkError;
  }
}

async function request(path, options = {}) {
  const method = String(options.method || "GET").toUpperCase();
  const hasBody = options.body !== undefined && options.body !== null;
  const requestInit = {
    method,
    credentials: "include",
    signal: options.signal,
    headers: {
      Accept: "application/json",
      ...(hasBody ? { "Content-Type": "application/json" } : {}),
      ...(method !== "GET" && method !== "HEAD" && getCsrfToken()
        ? { "X-CSRF-Token": getCsrfToken() }
        : {}),
      ...(options.headers || {}),
    },
    ...(hasBody ? { body: JSON.stringify(options.body) } : {}),
  };

  const response = await fetchWithNetworkRetry(
    buildUrl(path, options.query),
    requestInit,
    method,
  );

  const text = await response.text();
  let payload;
  try {
    payload = text ? JSON.parse(text) : {};
  } catch {
    const error = new Error("El servidor devolvió una respuesta inválida.");
    error.status = response.status;
    throw error;
  }

  if (response.status === 401) {
    clearStoredSession();
    window.dispatchEvent(new CustomEvent("mutual:unauthorized"));
  }

  if (!response.ok || payload?.ok === false) {
    const error = new Error(payload?.error?.message || payload?.mensaje || "No se pudo completar la operación.");
    error.status = response.status;
    error.code = payload?.error?.code || payload?.codigo;
    error.fields = payload?.error?.fields || {};
    error.correlationId = payload?.error?.correlation_id;
    throw error;
  }

  return payload?.data ?? payload;
}

export const apiGet = (path, query, options = {}) => request(path, { ...options, query, method: "GET" });
export const apiPost = (path, body, options = {}) => request(path, { ...options, body, method: "POST" });
export const apiPut = (path, body, options = {}) => request(path, { ...options, body, method: "PUT" });
export const apiPatch = (path, body, options = {}) => request(path, { ...options, body, method: "PATCH" });
export const apiDelete = (path, body, options = {}) => request(path, { ...options, body, method: "DELETE" });
