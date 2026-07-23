import { apiGet, apiPost } from "../../shared/httpClient";

export const solicitarInicioSesion = (credenciales) =>
  apiPost("auth/login", credenciales);

export const solicitarCierreSesion = () => apiPost("auth/logout", {});

export const consultarSesionActual = () => apiGet("auth/me");
