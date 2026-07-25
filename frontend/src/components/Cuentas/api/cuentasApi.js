import { apiGet } from "../../Global/api/httpClient";

export const getCuentasSociosStructure = () => apiGet("cuentas");
