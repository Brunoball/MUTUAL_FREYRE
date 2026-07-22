import { apiGet, apiPost } from "../../shared/httpClient";

export const loginRequest = (credentials) => apiPost("auth/login", credentials);
export const logoutRequest = () => apiPost("auth/logout", {});
export const currentSessionRequest = () => apiGet("auth/me");
