import { apiGet } from "../../shared/httpClient";

export const getCuentasSociosStructure = () => apiGet("cuentas");
