import { apiGet } from "../../_shared/httpClient";

export const getDashboard = () => apiGet("dashboard");
