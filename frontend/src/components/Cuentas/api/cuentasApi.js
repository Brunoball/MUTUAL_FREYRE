import { apiGet } from "../../_shared/httpClient";

export const getCuentasSociosStructure = () => apiGet("cuentas");
