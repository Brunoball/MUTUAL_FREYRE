import { apiGet } from "../../_shared/httpClient";

export const getValoresStructure = () => apiGet("valores");
