import { apiGet } from "../../shared/httpClient";

export const getContabilidadStructure = () => apiGet("contabilidad");
