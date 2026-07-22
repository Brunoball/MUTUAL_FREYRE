import { apiGet } from "../../shared/httpClient";

export const getPersonasStructure = () => apiGet("personas");
