import { apiGet } from "../../_shared/httpClient";

export const getBancosStructure = () => apiGet("bancos");
