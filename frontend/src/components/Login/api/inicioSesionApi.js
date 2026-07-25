import { apiGet, apiPost } from "../../_shared/httpClient";

export const solicitarInicioSesion = (credenciales) =>
  apiPost("auth/login", credenciales);

export const solicitarCierreSesion = () => apiPost("auth/logout", {});

export const consultarSesionActual = () => apiGet("auth/me");
