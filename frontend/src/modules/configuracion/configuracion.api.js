import {
  apiDelete,
  apiGet,
  apiPatch,
  apiPost,
  apiPut,
} from "../../shared/httpClient";

export const getConfiguracion = (options) =>
  apiGet("configuracion", { _ts: Date.now() }, options);

export const getUsuariosSistema = (options) =>
  apiGet("configuracion/usuarios", { _ts: Date.now() }, options);

export const crearUsuarioSistema = (payload) =>
  apiPost("configuracion/usuarios", payload);

export const actualizarUsuarioSistema = (id, payload) =>
  apiPut("configuracion/usuarios", payload, { query: { id } });

export const cambiarEstadoUsuarioSistema = (id, activo) =>
  apiPatch("configuracion/usuarios/estado", { activo }, { query: { id } });

export const eliminarUsuarioSistema = (id) =>
  apiDelete("configuracion/usuarios", undefined, { query: { id } });

export const getConfiguracionStructure = getConfiguracion;
