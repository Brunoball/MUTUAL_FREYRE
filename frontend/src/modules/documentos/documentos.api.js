import { apiGet } from "../../shared/httpClient";

export const getDocumentosStructure = () => apiGet("documentos");
