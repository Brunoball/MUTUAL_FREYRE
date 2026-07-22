import { apiGet } from "../../shared/httpClient";

export const getConfiguracionStructure = () => apiGet("configuracion");
