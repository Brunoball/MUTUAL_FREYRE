import { apiGet } from "../../shared/httpClient";

export const getBancosStructure = () => apiGet("bancos");
