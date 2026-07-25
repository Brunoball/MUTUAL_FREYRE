import { apiGet, apiPost } from "../../Global/api/httpClient";

export const solicitarInicioSesion = (credenciales) =>
  apiPost("auth/login", credenciales);

export const solicitarCierreSesion = () => apiPost("auth/logout", {});

export const consultarSesionActual = () => apiGet("auth/me");
